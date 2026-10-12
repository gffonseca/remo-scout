<?php
namespace Tests\Feature;
use App\Models\{Player,PlayerRoleAssessment,RecruitmentNeed,TacticalRole,Club,EnrichmentReview,ScoutingReport};
use App\Services\{RequirementCheck,PlayerEnrichment};
use App\Services\Import\PlayerImporter;
use App\Support\PlayerInsight;
class AuditRegressionTest extends ScoutTestCase {
 public function test_potential_must_not_satisfy_observed_requirement():void {
  $r=TacticalRole::first();$p=Player::create(['name'=>'Probe','primary_position'=>'CA']);
  $n=RecruitmentNeed::create(['title'=>'Probe','position'=>'CA','tactical_role_id'=>$r->id,'target_level'=>'B']);
  PlayerRoleAssessment::create(['player_id'=>$p->id,'tactical_role_id'=>$r->id,'kind'=>'potencial','level'=>'A','evidence'=>'projecao']);
  $this->assertStringStartsWith('Pendente',RequirementCheck::summary($n,$p));
 }
 public function test_unverified_manual_requirement_must_remain_pending():void {
  $p=Player::create(['name'=>'Probe','primary_position'=>'CA']);$n=RecruitmentNeed::create(['title'=>'Probe','position'=>'CA','mandatory_requirements'=>['Confirmar disponibilidade']]);
  $this->assertStringStartsWith('Pendente',RequirementCheck::summary($n,$p));
 }
 public function test_brazilian_thousands_without_decimal():void {$this->assertSame(25000.0,PlayerImporter::parseMoney('R$ 25.000'));}
 public function test_negative_money_string_rejected():void {$this->assertNull(PlayerImporter::parseMoney('-1000'));}
 public function test_admin_without_mfa_cannot_export():void {
  $this->actingAs($this->user('admin'));$p=Player::create(['name'=>'Probe']);
  $this->get(route('dossie.player',$p))->assertRedirect();
 }
 public function test_revert_restores_fields_of_existing_player():void {
  $p=Player::create(['name'=>'Probe','cbf_id'=>'11111']);$m=['name'=>'Nome','cbf_id'=>'CBF','height_cm'=>'Altura'];
  $plan=PlayerImporter::plan([['Nome'=>'Probe','CBF'=>'11111','Altura'=>'1,88']],$m,false);$b=PlayerImporter::commit($plan,'probe.csv',$m,null);
  $this->assertSame(188,$p->fresh()->height_cm);PlayerImporter::revert($b,null);$this->assertNull($p->fresh()->height_cm);
 }
 public function test_cbf_club_review_uuid_can_be_accepted():void {
  $a=Club::create(['name'=>'A']);$b=Club::create(['name'=>'B']);$p=Player::create(['name'=>'Probe','current_club_id'=>$a->id]);
  PlayerEnrichment::propose($p,'current_club_id',$b->id,'cbf');$r=EnrichmentReview::where('player_id',$p->id)->first();PlayerEnrichment::accept($r,null);
  $this->assertSame($b->id,$p->fresh()->current_club_id);
 }
 public function test_potential_report_not_current_level():void {
  $u=$this->user('coordenador');$p=Player::create(['name'=>'Probe']);ScoutingReport::create(['player_id'=>$p->id,'author_id'=>$u->id,'assessment_type'=>'potencial','level'=>'A','positives'=>'x','negatives'=>'y','status'=>'submetido','submitted_at'=>now()]);
  $this->assertNull(PlayerInsight::currentLevel($p));
 }
}
